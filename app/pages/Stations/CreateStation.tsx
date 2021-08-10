import React, { useCallback } from "react"
import { RouteComponentProps, Link } from "@reach/router"
import { v4 as uuidv4 } from "uuid"

import Station from "../../models/Station"
import { useStationContext } from "../../contexts/StationContext"
import { useCurrentPackageContext } from "../../contexts/CurrentPackageContext"

import StationForm, { StationFormSubmitHandler } from "./StationForm"

const CreateStation: React.FC<RouteComponentProps> = () => {
    const { currentPackage } = useCurrentPackageContext()
    const { reloadStations } = useStationContext()

    const onSubmit = useCallback<StationFormSubmitHandler>(
        async (values) => {
            values.slug = uuidv4()

            const newStation = new Station()
            newStation.patch(values)
            newStation.setPackage(currentPackage)

            const updated = (await newStation.save()).getModel()
            if (!updated) {
                throw new Error("Wasn't able to update station")
            }

            await reloadStations()
        },
        [currentPackage, reloadStations]
    )

    return (
        <>
            <Link
                className="button"
                to={`/packages/${currentPackage.getApiId()}/stations`}
            >
                Zurück
            </Link>
            <h1>Neue Station</h1>

            <StationForm onSubmit={onSubmit} />
        </>
    )
}

export default CreateStation
